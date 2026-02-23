# Stripe Connect Onboarding

How the Stripe Connect OAuth onboarding flow works for fork operators, and how to customize it.

## When to Use

- You're setting up Connect for the first time.
- You need to understand or customize the OAuth onboarding flow.
- You're debugging Connect onboarding issues.

## Critical Principles

1. **Standard accounts only** — Fork operators manage their own Stripe dashboard. No Express or Custom accounts.
2. **State parameter for CSRF** — A random state token is generated and stored in settings, then verified on callback.
3. **Three UI states** — Not Connected → Pending → Active. The UI adapts based on `connected_account_id` and account status.
4. **Redirect URI must match** — The callback URL in Stripe must exactly match `{APP_URL}/api/stripe/connect/callback`.

## Files

| File | Purpose |
|------|---------|
| `backend/app/Http/Controllers/Api/StripeConnectController.php` | Status, OAuth link, account links, disconnect |
| `backend/app/Http/Controllers/Api/StripeConnectCallbackController.php` | OAuth callback (code exchange) |
| `backend/app/Services/Stripe/StripeConnectService.php` | OAuth code exchange, account management |
| `frontend/app/(dashboard)/configuration/stripe/page.tsx` | Connect UI (3-state) |
| `backend/routes/web.php` | Connect callback route (browser redirect) |

## OAuth Flow

```
1. Admin clicks "Connect with Stripe"
   → Frontend calls GET /api/stripe/connect/oauth-link

2. Backend generates OAuth URL:
   → Stores random state token in settings
   → Returns Stripe OAuth URL with state, client_id, redirect_uri

3. Admin is redirected to Stripe:
   → Completes account creation/linking on Stripe

4. Stripe redirects to callback:
   → GET /api/stripe/connect/callback?code=ac_...&state=...

5. Callback controller:
   → Verifies state matches stored token
   → Exchanges code for connected account ID via StripeConnectService::exchangeOAuthCode()
   → Stores connected_account_id in settings
   → Redirects to /configuration/stripe?onboarding=complete&account_id=acct_...

6. Frontend:
   → Reads URL params, shows success toast, clears URL
   → UI updates to show connected status
```

## UI States

### Not Connected

No `connected_account_id` in settings. Shows:
- "Connect with Stripe" button → initiates OAuth flow

### Pending

`connected_account_id` exists but `charges_enabled` is false. Shows:
- "Complete Setup" button → creates an account link for `account_onboarding`
- Status indicator showing pending verification

### Active

`connected_account_id` exists and `charges_enabled` is true. Shows:
- Connected account ID
- "Open Stripe Dashboard" link → creates a login link
- "Disconnect" button → calls `StripeConnectService::disconnectAccount()` (OAuth deauthorize)

## Key Service Methods

```php
use App\Services\Stripe\StripeConnectService;

// Generate OAuth URL for onboarding
$connectService->createOAuthUrl($redirectUri);

// Exchange authorization code for account ID
$result = $connectService->exchangeOAuthCode($code);
// Returns: ['success' => true, 'stripe_user_id' => 'acct_...']

// Check account status
$result = $connectService->getAccountStatus($accountId);
// Returns: ['success' => true, 'status' => 'active', 'charges_enabled' => true, ...]

// Create account link for additional onboarding
$result = $connectService->createAccountLink($accountId, 'account_onboarding');
// Returns: ['success' => true, 'url' => 'https://connect.stripe.com/...']

// Create dashboard login link
$result = $connectService->createLoginLink($accountId);

// Disconnect (OAuth deauthorize)
$result = $connectService->disconnectAccount($accountId);
```

## Checklist

- [ ] Platform Client ID configured (pre-filled with default, override in Stripe Connect settings if needed)
- [ ] Redirect URI added in Stripe Dashboard (Settings → Connect → OAuth): `{APP_URL}/api/stripe/connect/callback`
- [ ] Connect callback route exists in `routes/web.php`
- [ ] OAuth flow completes and stores `connected_account_id`
- [ ] All three UI states display correctly

## Common Mistakes

- **❌ Wrong redirect URI** — Must match exactly including protocol (http vs https).
- **✅ Set to `{APP_URL}/api/stripe/connect/callback`** — Check in Stripe Dashboard → Connect → OAuth settings.

- **❌ Missing Platform Client ID** — OAuth URL generation will fail.
- **✅ The Platform Client ID is pre-configured with a default value.** Override it in the Stripe Connect settings page if needed. Found in Stripe Dashboard → Connect → Settings.

- **❌ Not handling the "pending" state** — User may complete OAuth but not finish Stripe onboarding.
- **✅ Show "Complete Setup" button** — Creates an account link to resume onboarding.

## Related

- [ADR-026: Stripe Connect Integration](../../adr/026-stripe-connect-integration.md)
- [Pattern: Stripe Service](../patterns/stripe-service.md)
- [Recipe: Setup Stripe](setup-stripe.md)
