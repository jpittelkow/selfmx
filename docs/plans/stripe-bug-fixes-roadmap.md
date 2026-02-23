# Stripe Connect Bug Fixes Roadmap

Post-review bug fixes for the Stripe Connect integration. Full code review identified 12 issues ranging from critical (API incompatibility) to low (defensive coding).

## Phase 1: Critical & High Priority

- [x] **Fix 1: `createLoginLink` won't work for Standard accounts** — `accounts->createLoginLink()` only works for Express/Custom, but `createAccount()` creates Standard type. Replaced with `getDashboardUrl()` returning `https://dashboard.stripe.com`. Also fixes popup blocker issue in frontend.
- [x] **Fix 2: `createAccountLink` uses backend URL for frontend routes** — Uses `config('app.url')` but refresh/return URLs are frontend routes. Changed to `config('app.frontend_url', config('app.url'))`.
- [x] **Fix 3: `handleAccountDeauthorized` doesn't clear `connected_account_id`** — App keeps charging a revoked account. Now clears `connected_account_id` and `connect_onboarding_state` via SettingService.
- [x] **Fix 4: Webhook event default status `processed` instead of `pending`** — Failed processing silently hidden. New migration changes default; service inserts as `pending` and updates to `processed` after handling.
- [x] **Fix 5: No webhook secret null guard** — Undefined behavior if secret not configured. Added explicit null check with RuntimeException.

## Phase 2: Medium Priority

- [x] **Fix 6: Partial refund always marked as fully `refunded`** — No distinction for partial refunds. Now checks `$charge->refunded` boolean and uses `partially_refunded` status when appropriate. Frontend status badge updated.
- [x] **Fix 7: OAuth state token not cleared on error paths** — Allows CSRF replay. State cleared immediately after `hash_equals` passes, before any other logic.
- [x] **Fix 8: Stale Stripe singleton when publishable key changes** — Admin switching test/live gets cached old instance. Now tracks `cachedKey` and auto-resets.

## Phase 3: Low Priority (Hardening)

- [x] **Fix 9: Missing audit log for settings reset** — Added `auditService->log('stripe.setting_reset', ...)` before reset.
- [x] **Fix 10: Missing `$hidden` on models** — Added `$hidden` to `StripeWebhookEvent` (payload) and `StripeCustomer` (metadata).
- [x] **Fix 11: `formatAmount` crash on invalid currency** — Wrapped in try/catch with fallback.
- [x] **Fix 12: search-pages `adminOnly` mismatch** — Changed `config-payments` to `adminOnly: false` to match nav permission.

## Key Files Modified

**Backend Services:**
- `backend/app/Services/Stripe/StripeConnectService.php`
- `backend/app/Services/Stripe/StripeWebhookService.php`

**Backend Controllers:**
- `backend/app/Http/Controllers/Api/StripeConnectController.php`
- `backend/app/Http/Controllers/Api/StripeConnectCallbackController.php`
- `backend/app/Http/Controllers/Api/StripeSettingController.php`

**Backend Models:**
- `backend/app/Models/StripeWebhookEvent.php`
- `backend/app/Models/StripeCustomer.php`

**Migration:**
- `backend/database/migrations/2026_02_21_100000_change_stripe_webhook_events_default_status.php`

**Frontend:**
- `frontend/lib/stripe.ts`
- `frontend/app/(dashboard)/configuration/stripe/page.tsx`
- `frontend/app/(dashboard)/configuration/payments/page.tsx`
- `frontend/lib/search-pages.ts`
