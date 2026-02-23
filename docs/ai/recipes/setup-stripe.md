# Set Up Stripe Payments

Step-by-step guide to enable and configure Stripe Connect for payment processing in a Sourdough deployment.

## When to Use

- You want to accept payments via Stripe.
- You want the platform to collect an application fee via Stripe Connect.
- You're setting up Stripe for the first time on a deployment.

## Critical Principles

1. **Complete platform setup first** — You need a Stripe account with Connect enabled before configuring in the app.
2. **Use test mode initially** — Always verify with test keys before switching to live.
3. **Connect is required for the free license** — Direct Stripe usage (bypassing Connect) requires a commercial license. See `backend/app/Services/Stripe/LICENSE.md`.
4. **All keys are stored encrypted** — Secret keys use SettingService with `encrypted: true` in the schema.

## Files

| File | Purpose |
|------|---------|
| `backend/config/stripe.php` | Config with env defaults |
| `backend/config/settings-schema.php` | `stripe` group definition |
| `backend/app/Providers/ConfigServiceProvider.php` | `injectStripeConfig()` boot-time injection |
| `.env.example` | Environment variable reference |
| `frontend/app/(dashboard)/configuration/stripe/page.tsx` | Admin configuration UI |

## Steps

### Fork Operator Quick Setup

If this deployment is a **fork** (not the platform itself), the setup is simple — you only need to complete the Connect OAuth flow:

1. Go to **Configuration > Stripe**.
2. Click **Connect Stripe Account** and complete the Stripe OAuth flow.
3. Return to the app — your status will show as "Pending" or "Active".

The Platform Client ID is pre-configured with the default value. Fork operators can adjust currency, fee percentage, and platform identifiers in the Stripe Connect section if needed.

### Platform Setup

The following steps are for the **platform operator** (the entity running the main Sourdough instance).

### 1. Stripe Platform Account Setup (one-time)

In the [Stripe Dashboard](https://dashboard.stripe.com/):

1. Create or upgrade your Stripe account and complete identity verification.
2. Enable **Stripe Connect** (Settings → Connect → Get started). Choose **Platform or Marketplace** with **Standard** accounts.
3. Configure platform branding (Settings → Connect → Branding: name, icon, color, website URL).
4. Configure OAuth settings (Settings → Connect → OAuth: note the **Platform Client ID**, add redirect URIs — `{APP_URL}/api/stripe/connect/callback`).
5. Note your credentials:
   - **Platform Account ID** (starts with `acct_`)
   - **Secret Key** (starts with `sk_test_` or `sk_live_`)
   - **Publishable Key** (starts with `pk_test_` or `pk_live_`)
   - **Platform Client ID** (starts with `ca_`)
6. Set up a webhook endpoint:
   - URL: `{APP_URL}/stripe/webhook`
   - Events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `account.updated`, `account.application.deauthorized`
   - Note the **Webhook Signing Secret** (starts with `whsec_`)

### 2. Configure Environment Variables (Option A)

Add to your `.env` file:

```bash
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_MODE=test
STRIPE_CURRENCY=usd
STRIPE_APPLICATION_FEE_PERCENT=1.0
STRIPE_PLATFORM_ACCOUNT_ID=acct_...
```

> **Note:** `STRIPE_ENABLED` and `STRIPE_PLATFORM_CLIENT_ID` are no longer needed. The Stripe module is always available, and the Platform Client ID is hardcoded with a default value that can be overridden via the admin UI.

### 3. Configure via Admin UI (Option B)

1. Go to **Configuration → Stripe**.
2. In the **Stripe Connect** section, configure currency, application fee %, and platform identifiers. The Platform Client ID is pre-filled with the default.
3. In the **API Keys** section, select mode (Test / Live) and enter API keys (Secret Key, Publishable Key, Webhook Secret).
4. Click **Save**.

### 4. Test Connection

Click the **Test Connection** button on the Stripe configuration page. This calls `StripeService::testConnection()` which retrieves your account info from the Stripe API.

### 5. Connect Onboarding

1. In the **Connect** section of the Stripe config page, click **Connect with Stripe**.
2. Complete the Stripe OAuth onboarding flow.
3. You'll be redirected back to the app with a connected account.
4. The UI will show the connected status with a link to the Stripe dashboard.

### 6. Switch to Live Mode

When ready for production:

1. Replace test keys with live keys in the Stripe configuration.
2. Switch mode from "Test" to "Live".
3. Update the webhook endpoint in Stripe to use live mode.
4. Test a real payment.

## Checklist

- [ ] Stripe account created with identity verification
- [ ] Connect enabled with Standard accounts
- [ ] OAuth redirect URI configured (`{APP_URL}/api/stripe/connect/callback`)
- [ ] API keys set (Secret, Publishable, Webhook Secret)
- [ ] Platform Account ID and Client ID configured
- [ ] Test Connection succeeds
- [ ] Webhook endpoint configured in Stripe dashboard
- [ ] Connect onboarding completed
- [ ] Test payment processed successfully

## Common Mistakes

- **❌ Forgetting the webhook secret** — Webhooks will fail signature verification without it.
- **✅ Always set `STRIPE_WEBHOOK_SECRET`** — Copy it from Stripe Dashboard → Webhooks → Signing secret.

- **❌ Using live keys in test mode** — Payments will process real charges.
- **✅ Match keys to the mode** — `sk_test_*` for test mode, `sk_live_*` for live mode.

- **❌ Wrong redirect URI in Stripe** — Connect OAuth callback will fail.
- **✅ Set redirect URI to `{APP_URL}/api/stripe/connect/callback`** — Must match exactly, including protocol.

## Related

- [ADR-026: Stripe Connect Integration](../../adr/026-stripe-connect-integration.md)
- [Pattern: Stripe Service](../patterns/stripe-service.md)
- [Recipe: Add Payment Flow](add-payment-flow.md)
