# ADR-026: Stripe Connect Integration

## Status

Accepted

## Date

2026-02-21

## Context

Sourdough needs an optional payment processing module that allows fork operators to accept payments through their own Stripe account while generating platform revenue via application fees. The module must be fully feature-gated (no Stripe code loads when disabled), follow the existing SettingService pattern for configuration, and integrate with usage tracking for cost visibility.

## Decision

We will integrate **Stripe Connect with destination charges** as an optional payment module.

### Stripe Connect Model

- **Standard connected accounts** — fork operators manage their own Stripe dashboard, disputes, and payouts (least operational burden for the platform).
- **Destination charges** with `application_fee_amount` — the platform collects a configurable fee (default 1%) automatically at the Stripe level.
- **OAuth onboarding** — fork operators connect their Stripe account via OAuth; the platform stores the connected account ID.

### Always Available

- The Stripe module is always available — there is no feature gate or `stripe.enabled` toggle.
- Navigation items for Stripe and Payment History are always visible in the sidebar.
- The `isEnabled()` check on `StripeService` only verifies that a secret key is configured.

### Configuration

- Settings stored via SettingService (`stripe` group in `settings-schema.php`).
- Environment variable fallback for all keys (e.g. `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`).
- `ConfigServiceProvider::injectStripeConfig()` loads settings at boot into `config('stripe.*')`.
- Secret keys are encrypted in the database per schema definition.

### Connect Onboarding

- OAuth flow: admin clicks "Connect with Stripe" → redirected to Stripe → callback at `GET /api/stripe/connect/callback` exchanges code for account ID.
- State parameter stored in settings for CSRF protection.
- Three UI states: Not Connected → OAuth redirect; Pending → Complete Setup via account link; Active → Dashboard link + Disconnect button.

### Webhook Handling

- Public endpoint at `POST /stripe/webhook` (no auth middleware — avoids collision with existing `/webhooks/{webhook}` admin routes).
- Signature verification via `stripe-php` SDK.
- Idempotent: `stripe_webhook_events` table deduplicates by `stripe_event_id` using a unique constraint; duplicate deliveries are silently skipped (return 200).
- Handled events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `account.updated`, `account.application.deauthorized`.

### Usage Tracking

- Payment events tracked via `UsageTrackingService::recordPayment()` following the existing `recordEmail()`/`recordSMS()` pattern.
- `budget_payments` setting enables budget alerting for payment costs.
- Visible in the Usage & Costs dashboard.

### Licensing

- Dual-licensed: MIT for core Sourdough, Sourdough Commercial License for Stripe files.
- Free via Stripe Connect; commercial license required for direct Stripe usage.
- License file at `backend/app/Services/Stripe/LICENSE.md`.

## Consequences

### Positive

- Always available: Stripe configuration page is accessible to all deployments.
- Platform monetization without manual billing — Stripe collects the fee automatically.
- Fork operators get a complete payment system with minimal setup (OAuth onboarding, pre-built UI).
- Standard connected accounts minimize platform operational burden (no dispute handling, no payout management).
- Usage tracking integration gives cost visibility alongside other integrations.

### Negative

- Dual license adds complexity for contributors and fork operators.
- Stripe Connect requires platform identity verification (manual Phase 0 in Stripe dashboard).
- OAuth callback flow adds a web route (`routes/web.php`) outside the standard API pattern.

### Neutral

- `stripe/stripe-php` is an optional Composer dependency.
- `@stripe/stripe-js` and `@stripe/react-stripe-js` are optional npm dependencies.

## Related Decisions

- [ADR-014: Database Settings with Env Fallback](014-database-settings-env-fallback.md) — settings storage pattern
- [ADR-012: Admin-Only Settings](012-admin-only-settings.md) — admin gating for configuration

## Notes

### Key files

- `backend/app/Services/Stripe/StripeService.php` — payment intents, customers, refunds
- `backend/app/Services/Stripe/StripeConnectService.php` — OAuth, account management, login links
- `backend/app/Services/Stripe/StripeWebhookService.php` — event handling, idempotency, usage tracking
- `backend/config/stripe.php` — config with env defaults
- `backend/config/settings-schema.php` — `stripe` group
- `backend/app/Providers/ConfigServiceProvider.php` — `injectStripeConfig()`
- `backend/app/Http/Controllers/Api/StripeSettingController.php` — settings CRUD + test connection
- `backend/app/Http/Controllers/Api/StripeConnectController.php` — Connect status, OAuth link, disconnect
- `backend/app/Http/Controllers/Api/StripeConnectCallbackController.php` — OAuth callback
- `backend/app/Http/Controllers/Api/StripePaymentController.php` — payment listing + create intent
- `backend/app/Http/Controllers/Api/StripeWebhookController.php` — public webhook endpoint
- `backend/app/Models/Payment.php`, `StripeCustomer.php`, `StripeWebhookEvent.php`
- `frontend/lib/stripe.ts` — Stripe.js loader, `useStripeSettings()`, `usePayments()` hooks
- `frontend/app/(dashboard)/configuration/stripe/page.tsx` — settings + Connect onboarding UI
- `frontend/app/(dashboard)/configuration/payments/page.tsx` — payment history
- `backend/app/Services/Stripe/LICENSE.md` — commercial license

### Recipes

- [Setup Stripe](../ai/recipes/setup-stripe.md)
- [Add Payment Flow](../ai/recipes/add-payment-flow.md)
- [Handle Stripe Webhooks](../ai/recipes/handle-stripe-webhooks.md)
- [Stripe Connect Onboarding](../ai/recipes/stripe-connect-onboarding.md)
