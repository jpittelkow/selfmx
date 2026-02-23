# Notification System Review — Findings & Roadmap

Full review of the notification system covering UI/UX, configuration, templates, backend robustness, Novu integration, and missing features including Stripe payment notifications.

## Findings

### A. UI/UX

#### A1. Notification Bell & Dropdown — Solid
- Clean dual-mode (local vs Novu) switching in `NotificationBell`
- Mobile-responsive: uses `Sheet` on mobile, `Popover` on desktop
- Unread badge with count (99+ cap) and ping animation
- Accessible: aria-labels, title attributes

**Issues found:**
- **A1a. No notification type icon or category.** All notifications look identical in the dropdown — backup failures, login alerts, and budget warnings all render the same way. No visual differentiation by severity or type.
- **A1b. No clickable action on notification items.** The `onClick` handler in `NotificationItem` is passed from the dropdown but only closes the popover. Notifications with actionable data (e.g., "storage critical" → link to storage settings) don't deep-link anywhere.
- **A1c. Dropdown shows 8 items max** (`DROPDOWN_LIMIT = 8`) with "View all" link, but no indication of how many total unread exist beyond the badge count.

#### A2. Full Notifications Page — Good
- Pagination, tab filtering (all/unread), select-all, bulk delete
- Proper offline awareness with `OfflineBadge`

**Issues found:**
- **A2a. Delete is sequential.** `handleDeleteSelected` loops through `selectedIds` with individual API calls rather than a batch endpoint. Deleting 10 notifications = 10 HTTP requests.
- **A2b. No filter by notification type.** Users can only filter by read/unread, not by category (auth, backup, storage, usage, etc.).
- **A2c. No sort option.** Always sorted newest-first (server default). No way to sort by type or read status.

#### A3. User Preferences — Good
- Per-channel enable/disable with optimistic updates
- Channel-specific settings (webhook URLs, phone numbers) with per-channel save
- WebPush subscription flow is well-handled (enable/disable/permission denied states)
- Novu fallback text when Novu is enabled

**Issues found:**
- **A3a. Toggle disabled when not configured but no explanation.** Switch is disabled with `(channel.settings.length > 0 && !channel.configured)` — the user sees a greyed-out toggle but no tooltip or message explaining they need to fill in settings first.
- **A3b. No per-type notification preferences.** Users can only enable/disable entire channels. They cannot say "I want backup alerts on Slack but not login alerts." This is a common expectation in notification systems.
- **A3c. No channel-specific test result feedback.** The test button shows a generic success/failure toast. No details about what was tested or what failed.

#### A4. Admin Notifications Page — Good
- Channel list with provider configured / available status badges
- Collapsible credential forms per provider
- SMS provider selector
- Test all channels with per-channel results grid
- Single save button for all credentials

**Issues found:**
- **A4a. All credentials in one form.** All 13 channels' credentials are in a single `<form>` with one save button. Saving Telegram credentials also re-submits Discord, Slack, Matrix, etc. credentials — wasteful and risks overwriting.
- **A4b. "Configured" status based on `watch()` values.** The CollapsibleCard status badge uses `watch("telegram_bot_token")` etc., which shows "Configured" when the field has any value — even if it hasn't been saved yet. Misleading.
- **A4c. No "Generate VAPID keys" button.** The Web Push card tells admins to run `npx web-push generate-vapid-keys` manually. This could be a one-click action.
- **A4d. No credential validation before save.** Webhook URLs, API keys, and tokens are all `z.string().optional()` — no format validation (e.g., URLs should look like URLs, Twilio SIDs start with "AC").

### B. Configuration & Validation Gaps

- **B1. Email templates separate from notification templates.** Email uses the `EmailTemplate` system (ADR-016) while push/inapp/chat use `NotificationTemplate`. Admins manage them on different pages. The `sendByType` orchestrator method requires `variables['title']` and `variables['message']` for email — inconsistent with the template-driven approach for other channel groups.
- **B2. SMS provider selection disconnected from user experience.** Admin selects one SMS provider (Twilio/Vonage/SNS), but the user preferences page shows the active SMS channel without explaining why only one SMS option appears. If the admin hasn't selected a provider, no SMS channel shows — no guidance.
- **B3. No connection test on credential save.** When an admin saves new credentials, they take effect immediately but there's no automatic validation. The admin must separately click "Test" for each channel.
- **B4. Channel availability vs provider configured confusion.** The admin page shows two independent states (configured + available), but toggling "available" is disabled when not configured. This is correct but the interaction isn't obvious — a disabled toggle without context.

### C. Template System

- **C1. Template variable management is manual.** Variables are hardcoded in `NotificationTemplateSeeder`. Adding a new variable to a notification type requires updating the seeder, and there's no runtime validation that the variables passed in `sendByType()` match what the template expects. Missing variables silently render as empty strings.
- **C2. No way to create custom notification types.** Admins can edit existing templates but cannot add new notification types (e.g., for Stripe payments). New types require a code change (seeder + migration).
- **C3. Good template editor UX.** Live preview with debounce, variable list with copy buttons, reset-to-default for system templates, send test. This is well-built.
- **C4. Template list shows raw type identifiers.** Types like `backup.completed`, `auth.login` are shown as-is. No human-readable labels or grouping by category.
- **C5. No email template integration.** The template editor only covers push/inapp/chat. Email content is managed elsewhere, creating a split experience for admins who want to customize "what does a backup failure notification say."

### D. Backend Robustness

- **D1. No retry logic for failed channels.** The orchestrator catches exceptions per-channel and logs them but never retries. A transient network error on a Discord webhook means that notification is permanently lost for that channel.
- **D2. No persistent failed notification queue / DLQ.** Failed deliveries are only captured in logs. There's no way to view, retry, or audit failed notifications from the admin UI.
- **D3. No rate limiting per channel.** A burst of events (e.g., rapid backup failures) could spam a user's Telegram/Discord/SMS with many identical notifications in seconds. No deduplication or throttling.
- **D4. No delivery audit trail.** The orchestrator returns results to callers but doesn't persist them. There's no "notification delivery log" showing what was sent, to whom, via which channels, and whether it succeeded.
- **D5. Database channel is always attempted.** In-app notifications are created regardless of user preferences (the `database` channel always returns true for `isUserChannelEnabled`). This is reasonable as a safety net but could accumulate noise.
- **D6. Orchestrator channel loop is not queued.** Despite `config/notifications.php` defining queue settings, the orchestrator's `send()`/`sendByType()` methods execute synchronously in the calling process. The queue config exists but isn't wired to actual async dispatch.

### E. Novu Integration

- **E1. Static workflow map.** `config/novu.php` maps notification types to workflow IDs. Admins cannot edit these mappings in the UI — requires config file changes.
- **E2. No fallback if Novu workflow is missing.** When `sendByType()` delegates to Novu and the workflow doesn't exist, it returns `{'novu': {'success': false, 'error': 'No workflow mapped'}}` — the local channels are never tried. No graceful degradation.
- **E3. No validation that workflows exist in Novu.** The `testConnection()` method lists workflows but doesn't check if mapped workflow IDs actually exist. An admin could configure everything, and notifications would silently fail because the workflow names don't match.
- **E4. Fragile Turbopack workaround.** `@novu/react` requires `ssr: false` dynamic import because it uses solid-js internally. This works but is brittle across Next.js version upgrades.
- **E5. Local templates ignored when Novu is enabled.** The admin can still edit notification templates in the UI, but they have no effect when Novu is active. No warning or indication of this.

### F. Missing Features

- **F1. No Stripe payment notification types.** With the Stripe integration complete, there are no notification templates for `payment.succeeded`, `payment.failed`, or `payment.refunded`. The webhook service tracks usage but doesn't notify users/admins.
- **F2. No per-type notification preferences.** Users can only enable/disable entire channels. Cannot configure "send backup alerts to Slack, login alerts to email only."
- **F3. No notification digest/batching.** Multiple rapid notifications are sent individually. No option for hourly/daily digest summaries.
- **F4. No scheduling or delay.** All notifications fire immediately. No "quiet hours" or deferred delivery.
- **F5. No admin visibility into per-user channel config.** Admins cannot see which users have configured which channels or how many users are reachable per channel.
- **F6. No resend capability.** If a notification failed to deliver on a channel, there's no way to retry it from the admin UI.
- **F7. No notification history/log for admins.** Beyond the in-app notifications table, there's no admin view of all notifications sent across all channels.

---

## Implementation Roadmap

### Phase 1: Quick Wins & Polish (Low effort, high UX impact) — Done

1. [x] **Notification type labels and icons** — Added `frontend/lib/notification-types.ts` with type→icon/label/category mapping. Updated `NotificationItem` to show type-specific icons. Updated template list to show human-readable labels grouped by category.
2. [x] **Toggle disabled tooltip** — Added "Enter settings below to enable" helper text when channel toggle is disabled on user preferences page.
3. [x] **Template list type labels** — Replaced raw `backup.completed` with "Backup Completed" in the template table. Grouped by category with section headers.
4. [x] **Batch delete endpoint** — Added `POST /api/notifications/delete-batch` with max 100 IDs. Frontend updated to use batch endpoint instead of sequential deletes.
5. [x] **Credential format validation** — Added zod validators: URL format for webhook URLs (Discord, Slack, ntfy, Matrix), phone format with 7+ digit requirement (Signal, Twilio, Vonage), "AC" prefix for Twilio SID, mailto:/https:// for VAPID subject.

### Phase 2: Stripe Payment Notifications — Done

1. [x] **Add payment notification types to seeder** — `payment.succeeded`, `payment.failed`, `payment.refunded` with templates for push/inapp/chat channel groups.
2. [x] **Add sample variables** — amount, currency, description, customer email, payment ID, refund reason.
3. [x] **Instrument StripeWebhookService** — Call `orchestrator->sendByType()` in payment webhook handlers (payment_intent.succeeded, payment_intent.payment_failed, charge.refunded).
4. [x] **Add variable descriptions** — Updated `NotificationTemplateController::variableDescriptions()` with payment-related variable descriptions.
5. [x] **Run seeder migration** — Templates added directly to existing seeder `run()`.

### Phase 3: Notification Deep Linking & Actions — Done

1. [x] **Add `action_url` to notifications model** — Read from `notification.data.action_url`; falls back to `getDefaultActionUrl()` from `notification-types.ts`.
2. [x] **Populate action URLs** — Default action URLs defined per type in `notification-types.ts` (storage → `/configuration`, payments → `/configuration/payments`, etc.).
3. [x] **Make notification items clickable** — `NotificationItem` marks as read and navigates to `action_url` on click; shows `ChevronRight` indicator when actionable.
4. [x] **Add notification type filter** — Category `<Select>` dropdown on the full notifications page; backend supports `?category=` param.

### Phase 4: Admin Credential UX Improvements — Done

1. [x] **Per-channel save buttons** — Split the single credential form into independent per-channel forms, each with its own `useForm` instance, Zod schema, and Save button inside each CollapsibleCard. Backend unchanged (partial updates already supported).
2. [x] **Auto-test on save** — After saving credentials for a channel, automatically runs the channel test and shows inline results (success/error with auto-dismiss after 10s). Manual Test button also uses the same inline display.
3. [x] **Generate VAPID keys button** — Added `POST /notification-settings/generate-vapid` endpoint using `Minishlink\WebPush\VAPID::createVapidKeys()`. Frontend button pre-fills form fields without auto-saving.
4. [x] **Novu active warning on templates page** — Shows warning `Alert` banner on the Notification Templates page when Novu is enabled, fetched via `GET /novu-settings`.

### Phase 5: Per-Type User Notification Preferences — Done

1. [x] **Per-type preferences stored as JSON blob** — Uses existing `settings` table (group=`notifications`, key=`type_preferences`) instead of a new table. Absence = enabled (only overrides stored).
2. [x] **Update orchestrator** — `isUserChannelEnabled()` now accepts `$type` parameter and checks per-type preferences. Falls back to channel-level preference if no type-specific override exists.
3. [x] **API endpoints** — `GET/PUT /user/notification-settings/type-preferences` for reading and updating per-type, per-channel preferences with validation.
4. [x] **Frontend preference matrix** — Collapsible "Fine-tune by notification type" section in user preferences with checkboxes for each type × channel intersection. Grouped by category. Only shows when 2+ channels enabled.

### Phase 6: Backend Robustness — Done

1. [x] **Add retry with exponential backoff** — `SendNotificationChannelJob` retries webhook-based channels up to 3 times with exponential backoff (1s, 5s, 25s). Database and email channels run with `$tries = 1`.
2. [x] **Notification delivery log** — Created `notification_deliveries` table with user_id, notification_type, channel, status (success/failed/rate_limited/skipped), error, attempt, attempted_at. Orchestrator and job both write delivery records.
3. [x] **Admin delivery log UI** — New admin page at `/configuration/notification-deliveries` with filterable table (channel, status, type, date range), stats cards, error detail modal. Protected by `notification_deliveries.view` permission.
4. [x] **Rate limiting per channel** — `NotificationRateLimiter` service checks successful deliveries per user per channel within a configurable time window. Admin UI on notifications page for rate_limit_enabled, rate_limit_max, rate_limit_window_minutes.
5. [x] **Async channel dispatch** — `SendNotificationChannelJob` dispatches to the default queue via Laravel's database queue driver. Orchestrator conditionally dispatches async (configurable via `queue_enabled` setting) or runs sync. Database channel always runs sync.

### Phase 7: Email Template Unification — Done

1. [x] **Add `email` channel group to notification templates** — Added `email` channel_group entries to `NotificationTemplateSeeder` for all 14 notification types with HTML email bodies. Migration seeds them on upgrade.
2. [x] **Update orchestrator** — `sendByType()` now renders per-type email templates via `NotificationTemplateService::render()` and sends via `EmailChannel::sendRendered()`. Falls back to generic `notification` EmailTemplate when no per-type template exists.
3. [x] **Unified template editor** — Template editor page shows channel group tabs (Push, In-App, Chat, Email) using sibling template data from the API. Email tab uses the TipTap rich text editor and renders HTML preview. Backend `show()` endpoint returns sibling template IDs for tab navigation.

### Phase 8: Novu Robustness — Done

1. [x] **Novu fallback to local** — `sendByType()` and `send()` in `NotificationOrchestrator` now attempt Novu first and fall back to local channels on failure (unmapped workflow or API error), logging a warning. Previously, failed Novu delivery silently dropped the notification.
2. [x] **Workflow map in admin UI** — Added `GET/PUT /novu-settings/workflow-map` endpoints. Workflow map stored as JSON in SettingService, injected at boot via `ConfigServiceProvider`. Frontend Novu settings page shows editable workflow mapping card with per-type inputs grouped by category, with Mapped/Unmapped badges. Added missing default mappings for payment and usage types in `config/novu.php`.
3. [x] **Workflow existence validation** — `NovuService::testConnection()` now fetches all workflows from Novu and compares against the local workflow map. Returns warnings for unmapped types and mapped workflows not found in Novu. Frontend displays warnings inline after test connection with "Missing" (red) and "Unmapped" (outline) badges.

### Phase 9: Code Review Bug Fixes — Done

Full code review of all 8 phases surfaced 48 issues (7 high, 17 medium, 21 low).

#### Batch 1: Security & Data Integrity

1. [x] **H1. Open redirect in `notification-item.tsx`** — Validate `action_url` from `notification.data` starts with `/` before passing to `router.push()`.
2. [x] **H2. Seeder migration overwrites all templates on upgrade** — Changed migration to use `firstOrCreate` for email templates only; made `defaults()` public.
3. [x] **H3. Duplicate migration timestamp `2026_02_22_000001`** — Renamed migration to `2026_02_22_000002_*`.
4. [x] **H4. `dangerouslySetInnerHTML` without sanitization** — Added DOMPurify sanitization to email preview.
5. [x] **H5. Unsafe array access in `NovuService::testConnection()`** — Guarded with `isset($workflow->triggers[0])`.
6. [x] **H6. Overly broad `isSubscriberAlreadyExistsException()` string matching** — Narrowed `str_contains('409')` to `preg_match('/\b409\b/')`.
7. [x] **H7. `updateWorkflowMap()` authorization gap** — Verified route already has `can:settings.edit` middleware.

#### Batch 2: Correctness Bugs

8. [x] **M1. `isConfigured` badge uses live form values** — Changed to use `initialValues` (saved server values).
9. [x] **M3. Payment templates hardcode `$` currency symbol** — Removed `$` prefix; now uses `{{amount}} {{currency}}`.
10. [x] **M7. `StripeWebhookService` uses `app()` instead of DI** — Injected `NotificationOrchestrator` via constructor.
11. [x] **M8. Dead `title`/`message` keys in Stripe webhook variables** — Removed from all three payment notification calls.
12. [x] **M9. Shallow snapshot in `toggleTypePreference`** — Uses `JSON.parse(JSON.stringify())` for deep copy.
13. [x] **M10. Batch delete doesn't reset page number** — Now calls `fetch(1, ...)` after batch delete.
14. [x] **M11. `sendByType()` attempts Novu when no workflow mapped** — Pre-checks `getWorkflowIdForType($type)` before Novu attempt.
15. [x] **M16. Auto-test fires on blank-field saves** — Guarded with `hasCredentials` check on submitted data.

#### Batch 3: Backend Robustness

16. [x] **M4. `typePreferencesCache` memory leak** — Cache cleared at end of `send()` and `sendByType()`.
17. [x] **M5/M6. Rate limiter misses queued notifications + no queued delivery record** — Added `STATUS_QUEUED` constant, write queued delivery in `dispatchChannel()`, count queued+success in rate limiter.
18. [x] **M12. `injectNovuConfig()` replaces workflow map instead of merging** — Uses `array_merge()`.
19. [x] **M13. `testConnection()` tests boot-time config, not just-saved settings** — Re-boots config and creates fresh NovuService before test.
20. [x] **M14. Three `SystemSetting::get()` calls per channel in rate limiter** — Batch-fetched into cached settings array.
21. [x] **M15. `updateTypePreference` queries DB on every toggle** — Added 5-minute cache for known types.
22. [x] **M17. Novu failure not logged to `NotificationDelivery`** — Writes `novu`/`failed` delivery record in both `sendByType()` and `send()`.
23. [x] **L19. Missing covering index for rate limiter query** — Added migration `2026_02_22_000003` to update index.

#### Batch 4: Frontend Polish

24. [x] **M2. Category→type maps independently maintained** — Backend now derives from `NotificationTemplate::distinct('type')` with cached query.
25. [x] **L1. Action URLs too generic** — Updated: backup→`/configuration/backup`, storage→`/configuration/storage`, suspicious_activity→`/configuration/audit`, usage→`/configuration/ai`.
26. [x] **L2. `channelGroupLabel` duplicated in templates page** — Exported `CHANNEL_GROUP_LABELS` from `notification-types.ts`, imported in both pages.
27. [x] **L6. Template category ordering inconsistency** — Fixed to iterate `getAllCategories()` for canonical ordering.
28. [x] **L7. No debounce on delivery log type filter** — Added 300ms debounce via `useRef` timer.
29. [x] **L11. `window.confirm()` for tab switch guard** — Replaced with shadcn/ui `AlertDialog`.
30. [x] **L12. `novu/page.tsx` `onTest` discards backend error** — Now extracts and shows API error message in toast.
31. [x] **L16. Type preference matrix hidden when < 2 channels** — Changed threshold from `< 2` to `< 1`.

#### Batch 5: Minor Cleanup

32. [x] **L3. Telegram bot token has no format validation** — Added `refine` for `<number>:<hash>` pattern.
33. [x] **L4. FCM JSON validation** — Added `refine` with `JSON.parse` try/catch.
34. [x] **L5. `onClick` fires after `router.push()`** — Reordered: `onClick` now fires before `router.push()`.
35. [x] **L8. `system.update` push body omits `{{version}}`** — Fixed to include `({{version}})`.
36. [x] **L9. Dead `$appName` variable in seeder** — Removed unused assignment.
37. [x] **L10. `sendTestNotification()` hardcodes "Sourdough"** — Now uses `config('app.name', 'Sourdough')`.
38. [x] **L13. `api_key` not trimmed in `injectNovuConfig()`** — Added `trim()`.
39. [x] **L15. Stats endpoint hardcoded to 7 days** — Accepts optional `?days=N` param (1-90).
40. [x] **L17. Missing audit log for `generateVapid`** — Added audit log call.
41. [x] **L18. No Novu workflow list pagination** — Added `limit: 100` to workflow list call.
42. [x] **L20. `email` in `NO_RETRY_CHANNELS` is dead code** — Removed; email is always sent sync by the orchestrator.
43. [x] **L21. Email body validator accepts empty HTML** — Added `refine` to strip HTML tags before checking.

---

## Key Files Reference

| Area | Key Files |
|------|-----------|
| Orchestrator | `backend/app/Services/Notifications/NotificationOrchestrator.php` |
| Channel metadata | `backend/app/Services/Notifications/NotificationChannelMetadata.php` |
| Template service | `backend/app/Services/NotificationTemplateService.php` |
| Template seeder | `backend/database/seeders/NotificationTemplateSeeder.php` |
| Novu service | `backend/app/Services/NovuService.php` |
| Config | `backend/config/notifications.php` |
| Admin channels | `backend/app/Http/Controllers/Api/NotificationChannelConfigController.php` |
| Admin settings | `backend/app/Http/Controllers/Api/NotificationSettingController.php` |
| Admin templates | `backend/app/Http/Controllers/Api/NotificationTemplateController.php` |
| User settings | `backend/app/Http/Controllers/Api/UserSettingController.php` |
| Bell component | `frontend/components/notifications/notification-bell.tsx` |
| Dropdown | `frontend/components/notifications/notification-dropdown.tsx` |
| Item component | `frontend/components/notifications/notification-item.tsx` |
| Novu inbox | `frontend/components/notifications/novu-inbox.tsx` |
| Notifications lib | `frontend/lib/notifications.tsx` |
| Full page | `frontend/app/(dashboard)/notifications/page.tsx` |
| User preferences | `frontend/app/(dashboard)/user/preferences/page.tsx` |
| Admin notifications | `frontend/app/(dashboard)/configuration/notifications/page.tsx` |
| Template list | `frontend/app/(dashboard)/configuration/notification-templates/page.tsx` |
| Template editor | `frontend/app/(dashboard)/configuration/notification-templates/[id]/page.tsx` |
| Novu settings | `frontend/app/(dashboard)/configuration/novu/page.tsx` |
| Novu config | `backend/config/novu.php` |
