# Stripe Connect Integration Roadmap

Integrate Stripe Connect into Sourdough as an optional payment module, using Stripe-enforced application fees (1%) for platform monetization. Dual-licensed: core Sourdough stays MIT; `backend/app/Services/Stripe/` gets a Sourdough Commercial License requiring Connect or a paid license for direct Stripe usage.

## Architecture

- **Stripe Connect with destination charges** -- fork operators connect their Stripe account to the Sourdough platform; Stripe automatically collects 1% application fee at the payment processing level
- **Standard connected accounts** -- fork operators manage their own Stripe dashboard, disputes, and payouts (least operational burden for the platform)
- **SettingService-backed configuration** -- all Stripe keys stored via the existing database+env fallback pattern (encrypted, admin-only)
- **Usage tracking integration** -- payment events tracked via existing `UsageTrackingService` and visible in the Usage & Costs dashboard
- **Always available** -- Stripe configuration and Connect onboarding are always accessible in the sidebar. No feature gate or enable toggle required. The Platform Client ID ships with a hardcoded default

## Phase 0: Platform Account Setup (Manual, One-Time)

Set up Sourdough as a Stripe Connect platform (done by the maintainer, not in code):

- [x] Create/upgrade Stripe account with identity verification
- [x] Enable Stripe Connect (Platform or Marketplace, Standard accounts)
- [x] Configure platform branding (name, icon, color, website URL)
- [x] Configure OAuth settings (Platform Client ID, redirect URIs)
- [x] Note platform credentials (account ID, secret key, publishable key, client ID)
- [x] Set up webhook endpoint for platform events
- [x] Test in test mode first

## Phase 1: Core Backend (Stripe Service Layer)

- [x] Add `stripe/stripe-php` dependency
- [x] Add `stripe` group to `backend/config/settings-schema.php` (enabled, keys, connect, fee %, currency)
- [x] Create `backend/config/stripe.php` config file (with platform_account_id, platform_client_id defaults)
- [x] Add `injectStripeConfig()` to `ConfigServiceProvider`
- [x] Create `backend/app/Services/Stripe/StripeService.php` (payment intents, customers, refunds)
- [x] Create `backend/app/Services/Stripe/StripeConnectService.php` (account creation, account links, status)
- [x] Create `backend/app/Services/Stripe/StripeWebhookService.php` (event handling, signature verification)
- [x] Create `payments` and `stripe_customers` migrations
- [x] Create `Payment` and `StripeCustomer` models
- [x] Add `PAYMENTS_VIEW`, `PAYMENTS_MANAGE` to `Permission` enum
- [x] Write unit tests for `StripeService` (payment intent creation, customer management, refunds) with mocked Stripe client
- [x] Write unit tests for `StripeConnectService` (account creation, status checks)

## Phase 2: Webhook Handling

- [x] Create `StripeWebhookController` (public route, signature verification, delegates to service)
- [x] Add `POST /stripe/webhook` route (public, no auth middleware — avoids collision with existing `/webhooks/{webhook}` admin routes)
- [x] Handle: payment_intent.succeeded, payment_intent.payment_failed, charge.refunded, account.updated, account.application.deauthorized
- [x] Implement idempotency: store processed `stripe_event_id` in a `stripe_webhook_events` table; skip duplicate deliveries (Stripe retries webhooks on failure)
- [x] Write tests for webhook signature verification (valid, invalid, replay) and idempotent event handling

## Phase 3: Connect Onboarding

- [x] Create `StripeConnectController` (status, create OAuth link, account links, login links, disconnect)
- [x] Create `StripeConnectCallbackController` (OAuth callback with state verification, code exchange, redirect)
- [x] Add `exchangeOAuthCode()` to `StripeConnectService`
- [x] Add `connected_account_id` and `connect_onboarding_state` to settings schema
- [x] Add Connect routes under `stripe/connect` prefix (settings.view/settings.edit permission)
- [x] Add Connect callback route (`GET /api/stripe/connect/callback`) in web.php
- [x] Write unit tests for controller and callback

## Phase 4: Stripe Settings + Payment API

- [x] Create `StripeSettingController` (show, update, test connection, delete key)
- [x] Create `StripePaymentController` (index, show, create intent, admin index)
- [x] Add settings and payment routes

## Phase 5: Frontend + Connect Onboarding UI *(done)*

### 5.1 Dependencies & Utilities
- [x] Add `@stripe/stripe-js` and `@stripe/react-stripe-js` npm dependencies
- [x] Create `frontend/lib/stripe.ts` — `getStripe(publishableKey)` singleton loader, `useStripeSettings()` and `usePayments()` hooks

### 5.2 Navigation
- [x] Add Stripe and Payment History items to the Integrations nav group in `frontend/app/(dashboard)/configuration/layout.tsx`
  - "Stripe" → `/configuration/stripe` (CreditCard icon, permission: settings.view, featureFlag: stripe)
  - "Payment History" → `/configuration/payments` (Receipt icon, permission: payments.view, featureFlag: stripe)
- [x] Add `payments.view` to `CONFIG_ACCESS_PERMISSIONS`
- [x] ~~Feature-gate~~ Removed: Stripe nav items are always visible (no feature flag). `stripe_enabled` removed from public settings

### 5.3 Stripe Configuration Page (`/configuration/stripe`)
- [x] Create page with single `useForm` + zod schema for all stripe settings
- [x] Section A — Stripe Connect: 3-state connect UI + currency, application fee %, platform account ID, platform client ID (pre-filled default)
- [x] Section B — API Keys: mode (test/live), API keys (masked), webhook secret, Test Connection button, SaveButton
- [x] OAuth callback handling: read `useSearchParams()` in Suspense, handle `onboarding=complete&account_id=` and `error=` params, toast + clear URL

### 5.4 Payment History Page (`/configuration/payments`)
- [x] Admin toggle: "My Payments" (`GET /api/payments`) vs "All Payments" (`GET /api/payments/admin`)
- [x] Table: Date, Description, Amount (cents→dollars), Status (badge), Fee (admin), User (admin)
- [x] Pagination (Laravel paginated response)

### 5.5 Search Registration
- [x] Add Stripe and Payments entries to `backend/config/search-pages.php`
- [x] Add matching entries to `frontend/lib/search-pages.ts`

## Phase 6: Usage Tracking Integration

- [x] Add `INTEGRATION_PAYMENTS = 'payments'` constant to `IntegrationUsage` model
- [x] Add `recordPayment()` convenience method to `UsageTrackingService` (following `recordEmail()`/`recordSMS()` pattern)
- [x] Instrument `StripeWebhookService` with `recordPayment()` for payment_processed and refund_processed events
- [x] Add `budget_payments` to usage settings schema
- [x] Register `budget_payments` mapping in `UsageAlertService`

## Phase 7: Licensing

- [x] Create `backend/app/Services/Stripe/LICENSE.md` (Sourdough Commercial License)
- [x] Ensure license scope covers both backend (`backend/app/Services/Stripe/`) and frontend (`frontend/lib/stripe.ts`, `frontend/app/(dashboard)/configuration/stripe/`, `frontend/app/(dashboard)/configuration/payments/`) Stripe files
- [x] Update root `LICENSE` to reference dual-license model
- [x] Update `FORK-ME.md` with Payments section

## Phase 8: Documentation

- [x] Create ADR-026: Stripe Connect Integration
- [x] Create recipes: setup-stripe, add-payment-flow, handle-stripe-webhooks, stripe-connect-onboarding
- [x] Create patterns: stripe-service, stripe-webhooks
- [x] Update context-loading.md and README.md with Payments/Stripe task type
- [x] Add help articles and search entries
- [x] Add Stripe to Integration Costs table
- [x] Add Stripe env vars to .env.example

## Key Files (When Complete)

**Backend:**
- `backend/config/stripe.php`
- `backend/app/Services/Stripe/StripeService.php`
- `backend/app/Services/Stripe/StripeConnectService.php`
- `backend/app/Services/Stripe/StripeWebhookService.php`
- `backend/app/Http/Controllers/Api/StripeSettingController.php`
- `backend/app/Http/Controllers/Api/StripeConnectController.php`
- `backend/app/Http/Controllers/Api/StripeWebhookController.php`
- `backend/app/Http/Controllers/Api/StripePaymentController.php`
- `backend/app/Models/Payment.php`
- `backend/app/Models/StripeCustomer.php`

**Frontend:**
- `frontend/app/(dashboard)/configuration/stripe/page.tsx`
- `frontend/app/(dashboard)/configuration/payments/page.tsx`
- `frontend/lib/stripe.ts`

**Documentation:**
- `docs/adr/026-stripe-connect-integration.md`
- `docs/ai/recipes/setup-stripe.md`
- `docs/ai/recipes/add-payment-flow.md`
- `docs/ai/recipes/handle-stripe-webhooks.md`
- `docs/ai/recipes/stripe-connect-onboarding.md`
- `docs/ai/patterns/stripe-service.md`
- `docs/ai/patterns/stripe-webhooks.md`
