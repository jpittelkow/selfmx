# Stripe Service Pattern

Use `StripeService` for all payment operations. Do not use the Stripe PHP SDK directly — the service handles client initialization, connected account routing, and application fee calculation.

## Usage

```php
use App\Services\Stripe\StripeService;

// Check if Stripe is configured (secret key present)
if ($stripeService->isEnabled()) { ... }

// Test connection (retrieves account info from Stripe API)
$result = $stripeService->testConnection();
// Returns: ['success' => true, 'account_id' => 'acct_...']

// Create or retrieve a Stripe customer for a user
$result = $stripeService->createCustomer($user);
// Returns: ['success' => true, 'customer_id' => 'cus_...']
// Idempotent: returns existing customer if already created

// Create payment intent with destination charge + application fee
$result = $stripeService->createPaymentIntent([
    'amount' => 2000,                         // cents (required)
    'connected_account_id' => 'acct_...',     // destination (required)
    'currency' => 'usd',                      // optional, defaults to config
    'customer_id' => 'cus_...',               // optional
    'description' => 'Order #123',            // optional
    'metadata' => ['order_id' => 123],        // optional
]);
// Returns: ['success' => true, 'payment_intent_id' => 'pi_...', 'client_secret' => '...']
// Application fee is calculated automatically from config('stripe.application_fee_percent')

// Refund (full or partial)
$result = $stripeService->refund('pi_...', 500);  // partial: 500 cents
$result = $stripeService->refund('pi_...');        // full refund (omit amount)
// Returns: ['success' => true, 'refund_id' => 're_...']
```

## Availability Check

Call `isEnabled()` before operations. The service checks whether a secret key is configured. There is no separate feature gate — Stripe is always available in the UI, but operations require a valid secret key.

## Connect (StripeConnectService)

```php
use App\Services\Stripe\StripeConnectService;

// Create a Standard connected account
$connectService->createAccount($user);

// Exchange OAuth code for connected account ID
$connectService->exchangeOAuthCode($code);

// Check account status (pending / pending_verification / active)
$connectService->getAccountStatus($accountId);

// Create account link for onboarding or updating
$connectService->createAccountLink($accountId, 'account_onboarding');

// Create login link for connected account's Stripe dashboard
$connectService->createLoginLink($accountId);

// Disconnect (OAuth deauthorize)
$connectService->disconnectAccount($accountId);
```

## Return Value Pattern

All service methods return arrays with a `success` boolean. On failure, an `error` string is included:

```php
$result = $stripeService->createPaymentIntent([...]);
if (!$result['success']) {
    // $result['error'] contains the error message
    return response()->json(['error' => $result['error']], 500);
}
// $result['payment_intent_id'], $result['client_secret']
```

**Key files:** `backend/app/Services/Stripe/StripeService.php`, `backend/app/Services/Stripe/StripeConnectService.php`, `backend/config/stripe.php`, `backend/config/settings-schema.php`.

**Related:** [Recipe: Setup Stripe](../recipes/setup-stripe.md), [Recipe: Add Payment Flow](../recipes/add-payment-flow.md), [ADR-026](../../adr/026-stripe-connect-integration.md).
