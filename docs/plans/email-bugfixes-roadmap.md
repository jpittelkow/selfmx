# Email Bug Fixes Roadmap

Critical email bugs discovered in v0.2.3 that need resolution before continuing Phase 7/8 work.

**Status**: Complete
**Priority**: High — these affect core email usability
**Created**: 2026-03-02
**Completed**: 2026-03-03

---

## Resolved Bugs

### Bug 1: Emails Display as Unread Despite Being Read — FIXED

**Resolution**: Already fixed in codebase. `EmailThreadController::index()` includes `effective_is_read` via `COALESCE(email_user_states.is_read, emails.is_read)` left-join on the `latestEmail` eager load. Frontend `thread-list.tsx` uses `effective_is_read ?? is_read` fallback chain.

**Additional fix (2026-03-03)**: Search results in `mail/page.tsx` were not propagating `effective_is_read` when constructing synthetic thread objects. Added `effective_is_read` to the search result mapping.

### Bug 2: Sent Mail "Queued" Status Never Updates — FIXED

**Resolution**: `DomainService::createDomain()` already registers delivery event webhooks (`delivered`, `bounced`, `complained`) for Mailgun domains. Webhook routes (`/api/email/webhook/{provider}/events`) are registered and functional. `EmailWebhookController::handleEvent()` correctly parses events and updates `delivery_status` on emails.

**Additional fix (2026-03-03)**: Webhook setup failures during domain creation were silently logged. Changed `createDomain()` to return warnings array alongside the domain, surfaced in the API response, and displayed as warning toasts on the frontend.

### Bug 3: Sent Emails Appearing in Inbox — FIXED

**Resolution**: Already fixed in codebase. `EmailThreadController::index()` includes `->where('direction', 'inbound')` in the `whereHas` clause. Sent view correctly fetches via `/email/messages?direction=outbound`.

### Bug 4: Inbound Routes Not Showing in Mailgun Domain Settings — FIXED

**Resolution**: Already fixed in codebase. `MailgunProvider::listRoutes()` uses regex-based filtering (`preg_match`) to match domain in Mailgun route expressions like `match_recipient('.*@example.com')`, handling quote characters and expression boundaries correctly.

### Bug 5: Tracking Toggles Don't Persist State — FIXED

**Resolution**: Already fixed in codebase. `MailgunProvider` methods use `managementRequestOrFail()` which throws `MailgunApiException` on failure. `MailgunManagementController` wraps calls in `wrapMailgunCall()` which catches exceptions and returns proper error HTTP responses. Frontend `TrackingTab` re-fetches state after PUT and validates the change persisted, showing an error toast on mismatch.

### Bug 6: Auto-Configure Webhooks Does Nothing — FIXED

**Resolution**: Already fixed in codebase. `MailgunProvider::createWebhook()` uses `managementRequestOrFail()` (throws on failure). `autoConfigureWebhooks()` catches per-event `MailgunApiException`, returns HTTP 502 if all fail, HTTP 207 with error details on partial failure, and 200 on full success. Frontend checks `res.data.errors` and shows appropriate warning/error toasts.

### Bug 7: DKIM and Webhooks Pages Error Handling — FIXED

**Resolution**: Fixed by the same `managementRequestOrFail()` + `wrapMailgunCall()` pattern that resolved Bugs 5 and 6. All Mailgun management API calls properly surface errors.

---

## Success Criteria — All Met

- [x] Mailgun API errors surface as visible error toasts (not false success messages)
- [x] Auto-configure webhooks actually creates webhooks in Mailgun
- [x] All read emails show without the blue unread indicator
- [x] Sent emails only appear in the Sent view, not the Inbox
- [x] Email delivery status updates from "Queued" to "Delivered" (or "Bounced"/"Failed") via Mailgun webhooks
- [x] Existing Mailgun inbound routes display correctly in domain settings
- [x] Tracking toggle state persists across page refreshes
- [x] Domain creation surfaces webhook setup warnings to the user
